<?php

namespace App\Form\Admin;

use App\DTO\PlaceBlockData;
use App\Entity\Room;
use App\Enum\PlaceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The "add a block of places" form — a venue holds hundreds of seats, and
 * entering them one by one would be unusable.
 */
class PlaceBlockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('room', EntityType::class, [
                'label' => 'Room',
                'class' => Room::class,
                'choice_label' => 'name',
                'placeholder' => 'Choose a room',
                'attr' => ['class' => 'form__control'],
                'constraints' => [new Assert\NotNull(message: 'The room is required.')],
            ])
            ->add('rowLabel', TextType::class, [
                'label' => 'Row',
                'required' => false,
                'empty_data' => '',
                'help' => 'The row the places belong to — leave it empty for the standing slots.',
                'attr' => ['class' => 'form__control', 'placeholder' => 'A'],
                'constraints' => [new Assert\Length(max: 8)],
            ])
            ->add('from', IntegerType::class, [
                'label' => 'From',
                'attr' => ['class' => 'form__control', 'placeholder' => '1'],
                'constraints' => [
                    new Assert\PositiveOrZero,
                    new Assert\LessThanOrEqual(999),
                ],
            ])
            ->add('to', IntegerType::class, [
                'label' => 'To',
                'attr' => ['class' => 'form__control', 'placeholder' => '40'],
                'constraints' => [
                    new Assert\PositiveOrZero,
                    new Assert\LessThanOrEqual(999),
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    'Numbered seat' => PlaceType::Seat->value,
                    'Standing slot' => PlaceType::StandingSlot->value,
                ],
                'choice_translation_domain' => false,
                'expanded' => true,
                'required' => true,
            ])
            ->add('codePrefix', TextType::class, [
                'label' => 'Code prefix',
                'required' => false,
                'empty_data' => '',
                'help' => 'Prepended to every generated code — ST- for the standing slots.',
                'attr' => ['class' => 'form__control', 'placeholder' => 'ST-'],
                'constraints' => [new Assert\Length(max: 8)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlaceBlockData::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'admin_place_block';
    }
}
