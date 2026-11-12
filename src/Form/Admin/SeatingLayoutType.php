<?php

namespace App\Form\Admin;

use App\DTO\SeatingLayoutFormData;
use App\Entity\Room;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The seating layout form: a versioned configuration of a room.
 */
class SeatingLayoutType extends AbstractType
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
            ->add('name', TextType::class, [
                'label' => 'Name',
                'help' => 'For example "Standard" or "Concert". The version number is added to the name.',
                'attr' => ['class' => 'form__control'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The name is required.'),
                    new Assert\Length(max: 150),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['class' => 'form__control', 'rows' => 3],
                'constraints' => [new Assert\Length(max: 2000)],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'The configuration is in service',
                'required' => false,
                'help' => 'An inactive configuration can still be used by a performance, but it is not proposed any more.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SeatingLayoutFormData::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'admin_seating_layout';
    }
}
