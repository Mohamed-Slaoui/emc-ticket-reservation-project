<?php

namespace App\Form\Admin;

use App\DTO\PlaceFormData;
use App\Entity\Room;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The place form: one seat or one standing slot.
 */
class PlaceType extends AbstractType
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
            ->add('code', TextType::class, [
                'label' => 'Code',
                'help' => 'The code identifies the place inside its room, for example A-01 or ST-001.',
                'attr' => ['class' => 'form__control'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The code is required.'),
                    new Assert\Length(max: 16),
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    'Numbered seat' => \App\Enum\PlaceType::Seat->value,
                    'Standing slot' => \App\Enum\PlaceType::StandingSlot->value,
                ],
                'choice_translation_domain' => false,
                'expanded' => true,
                'required' => true,
                'attr' => ['class' => 'form__radios'],
            ])
            ->add('rowLabel', TextType::class, [
                'label' => 'Row',
                'required' => false,
                'empty_data' => '',
                'help' => 'The row the place belongs to — A for the first row of the room.',
                'attr' => ['class' => 'form__control', 'placeholder' => 'A'],
                'constraints' => [new Assert\Length(max: 8)],
            ])
            ->add('seatNumber', IntegerType::class, [
                'label' => 'Number',
                'required' => false,
                'help' => 'The position of the place inside its row.',
                'attr' => ['class' => 'form__control', 'placeholder' => '1'],
                'constraints' => [new Assert\PositiveOrZero],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'The place is in service',
                'required' => false,
                'help' => 'A place out of service is not sold, but it stays in the configuration.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlaceFormData::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'admin_place';
    }
}
